<?php

namespace App\Form\Admin;

use App\DTO\UserFormData;
use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The account form — creation and edition.
 */
class UserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $isNew = (bool) $options['is_new'];

        $builder
            ->add('email', EmailType::class, [
                'label' => 'Email address',
                'attr' => ['class' => 'form__control', 'autocomplete' => 'email'],
                'constraints' => [
                    new Assert\NotBlank(message: 'The email address is required.'),
                    new Assert\Email(message: 'This is not a valid email address.'),
                ],
            ])
            ->add('firstName', TextType::class, [
                'label' => 'First name',
                'attr' => ['class' => 'form__control', 'autocomplete' => 'given-name'],
                'constraints' => [
                    new Assert\NotBlank(message: 'The first name is required.'),
                    new Assert\Length(max: 100),
                ],
            ])
            ->add('lastName', TextType::class, [
                'label' => 'Last name',
                'attr' => ['class' => 'form__control', 'autocomplete' => 'family-name'],
                'constraints' => [
                    new Assert\NotBlank(message: 'The last name is required.'),
                    new Assert\Length(max: 100),
                ],
            ])
            ->add('phone', TextType::class, [
                'label' => 'Phone',
                'required' => false,
                'attr' => ['class' => 'form__control', 'autocomplete' => 'tel'],
                'constraints' => [new Assert\Length(max: 30)],
            ])
            ->add('role', ChoiceType::class, [
                'label' => 'Role',
                'choices' => [
                    'Customer — buys tickets online' => User::ROLE_CUSTOMER,
                    'Box office — sells at the counter' => User::ROLE_BOX_OFFICE,
                    'Administrator — configures the venue' => User::ROLE_ADMIN,
                ],
                'choice_translation_domain' => false,
                'expanded' => true,
                'required' => true,
                'help' => 'The roles are cumulative: the administrator can do everything the box office and the customer can.',
            ])
            ->add('isActive', CheckboxType::class, [
                'label' => 'The account is enabled',
                'required' => false,
                'help' => 'A disabled account cannot sign in.',
            ]);

        if ($isNew) {
            $builder->add('plainPassword', TextType::class, [
                'label' => 'Password',
                'attr' => ['class' => 'form__control', 'autocomplete' => 'new-password'],
                'constraints' => [
                    new Assert\NotBlank(message: 'The password is required.'),
                    new Assert\Length(min: 8, minMessage: 'The password must be at least 8 characters long.'),
                ],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => UserFormData::class,
            'is_new' => false,
        ]);

        $resolver->setAllowedTypes('is_new', 'bool');
    }

    public function getBlockPrefix(): string
    {
        return 'admin_user';
    }
}
