<?php

namespace App\Form\Admin;

use App\DTO\EventFormData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The event form: a concert, a theatre piece, a conference.
 */
class EventType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Title',
                'help' => 'The name of the programme — it is shown to the customers.',
                'attr' => ['class' => 'form__control'],
                'constraints' => [
                    new Assert\NotBlank(message: 'The title is required.'),
                    new Assert\Length(max: 150),
                ],
            ])
            ->add('type', ChoiceType::class, [
                'label' => 'Type',
                'choices' => [
                    'Concert' => \App\Enum\EventType::Concert->value,
                    'Theatre' => \App\Enum\EventType::Theatre->value,
                    'Conference' => \App\Enum\EventType::Conference->value,
                    'Other' => \App\Enum\EventType::Other->value,
                ],
                'choice_translation_domain' => false,
                'required' => true,
                'attr' => ['class' => 'form__control'],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => ['class' => 'form__control', 'rows' => 4],
                'constraints' => [new Assert\Length(max: 2000)],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EventFormData::class,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'admin_event';
    }
}
